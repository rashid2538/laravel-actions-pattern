# Laravel Actions Pattern

A reusable skill for Claude Code / Cursor that enforces the **Action pattern** across all Laravel entry points: HTTP controllers, Artisan CLI commands, Livewire components, and queue jobs.

## What It Does

This skill teaches Claude to route **all business logic** through Action classes, keeping controllers, commands, and components as thin bridges that validate input, build an Action, and call `execute()`.

```
HTTP Request ──> ActionableRequest ──> getAction() ──> BaseAction::execute()
Artisan CLI  ──> ActionableCommand ──> getAction() ──> BaseAction::execute()
Livewire/Job ──> direct construction ───────────────> BaseAction::execute()
```

## The Three Pillars

### 1. BaseAction

The root of all actions. Lives at `app/Actions/BaseAction.php`.

```php
abstract class BaseAction
{
    protected readonly ?User $user;

    public function __construct(protected readonly array $data = [], ?User $user = null)
    {
        $this->user = $user ?? Auth::user();
    }

    abstract public function execute(): mixed;
}
```

**Key conventions:**
- `$data` is always a flat key-value array of validated scalar input
- `$user` falls back to `Auth::user()` when null (CLI/queue contexts pass null)
- Domain model dependencies (e.g., `Order`, `Site`) are promoted as `public readonly` constructor params **after** `$data` and `$user`
- `execute()` returns a typed value — the caller decides how to present it

**Example:**

```php
class UpdateOrderAction extends BaseAction
{
    public function __construct(
        array $data,
        ?User $user,
        public readonly Order $order,
    ) {
        parent::__construct($data, $user);
    }

    public function execute(): Order
    {
        $this->order->update($this->data);
        return $this->order->fresh();
    }
}
```

### 2. ActionableRequest (HTTP Bridge)

Lives at `app/Http/Requests/ActionableRequest.php`. Every HTTP request that performs a write or triggers business logic extends this.

```php
abstract class ActionableRequest extends FormRequest
{
    abstract public function getAction(): BaseAction;

    public function process(): mixed
    {
        return $this->getAction()->execute();
    }
}
```

**Usage in a controller:**

```php
// The request handles validation, authorization, and action building
class CreateOrderRequest extends ActionableRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Order::class);
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'exists:products,id'],
            'quantity'    => ['required', 'integer', 'min:1'],
        ];
    }

    public function getAction(): BaseAction
    {
        return new CreateOrderAction($this->validated(), $this->user());
    }
}

// The controller is a one-liner
class OrderController extends Controller
{
    public function store(CreateOrderRequest $request): JsonResponse
    {
        return response()->json($request->process(), 201);
    }
}
```

### 3. ActionableCommand (CLI Bridge)

Lives at `app/Console/Commands/ActionableCommand.php`. Every Artisan command that performs business logic extends this.

```php
abstract class ActionableCommand extends Command
{
    abstract protected function getAction(): BaseAction;
    abstract protected function displayResult(mixed $result): void;

    public function handle(): int
    {
        if (! $this->beforeAction()) {
            return self::FAILURE;
        }

        try {
            $result = $this->getAction()->execute();
            $result = $this->afterAction($result);
            $this->displayResult($result);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->displayError($e);
            return self::FAILURE;
        }
    }

    protected function beforeAction(): bool { return true; }
    protected function afterAction(mixed $result): mixed { return $result; }
    protected function displayError(\Throwable $e): void { $this->error($e->getMessage()); }
}
```

**Lifecycle hooks:**
- `beforeAction()` — return `false` to abort (e.g., `return $this->confirm('Proceed?')`)
- `afterAction($result)` — transform the Action's return value before display (passthrough by default)

**Usage:**

```php
class CreateOrderCommand extends ActionableCommand
{
    protected $signature = 'orders:create {product} {--quantity=1}';
    protected $description = 'Create a new order';

    protected function getAction(): BaseAction
    {
        $product = Product::findOrFail($this->argument('product'));

        return new CreateOrderAction(
            ['product_id' => $product->id, 'quantity' => (int) $this->option('quantity')],
            null,
        );
    }

    protected function displayResult(mixed $result): void
    {
        $this->info("Order {$result->id} created successfully.");
    }
}
```

## When to Use Each Bridge

| Scenario | Bridge |
|---|---|
| Form submission, API endpoint | `ActionableRequest` in a controller |
| Artisan command that mutates data | `ActionableCommand` |
| Livewire component action | Construct Action directly, call `->execute()` |
| Queue job wrapping an operation | Construct Action directly in `handle()` |
| Read-only Artisan command | Regular `Command` (no Action needed) |
| Scheduler/dispatcher command | Regular `Command` (orchestration, not business logic) |

## Installation

Copy the three reference files into your Laravel project:

```
references/BaseAction.php        --> app/Actions/BaseAction.php
references/ActionableRequest.php --> app/Http/Requests/ActionableRequest.php
references/ActionableCommand.php --> app/Console/Commands/ActionableCommand.php
```

Then create domain actions in `app/Actions/{Domain}/` extending `BaseAction`.

## Anti-Patterns

**Logic in controllers or commands** — all business logic belongs in Actions.

**Models in `$data`** — the `$data` array is for scalar validated input. Models are explicit constructor params.

**Actions aware of their entry point** — never check `app()->runningInConsole()` or similar inside an Action. Actions are entry-point agnostic.

**Overriding `handle()` on ActionableCommand** — use `getAction()` and `displayResult()` instead. For pre-flight checks, override `beforeAction()` or add guard logic in `getAction()`.

## File Structure

```
.cursor/skills/laravel-actions-pattern/
├── SKILL.md                              # Skill instructions for Claude
├── README.md                             # This file
└── references/
    ├── BaseAction.php                    # Abstract action base class
    ├── ActionableRequest.php             # HTTP bridge (FormRequest)
    └── ActionableCommand.php             # CLI bridge (Artisan Command)
```

## License

MIT
