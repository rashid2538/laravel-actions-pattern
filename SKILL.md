---
name: laravel-actions-pattern
description: "Apply when creating controllers, Artisan commands, Livewire components, or queue jobs that perform business logic. Enforces the Action pattern: all business logic lives in Action classes extending BaseAction; HTTP requests use ActionableRequest, CLI commands use ActionableCommand, and Livewire/Jobs call Actions directly. Triggers on: creating a controller method, adding a form request, writing an Artisan command, moving logic out of a controller or command, creating a new domain operation, or reviewing code that has business logic in a controller/command."
license: MIT
metadata:
  author: rashid
---

# Laravel Actions Pattern

A unified pattern for routing **all business logic** through Action classes, regardless of entry point (HTTP, CLI, Livewire, queue job). Three bridge classes connect the framework's entry points to a single `BaseAction` base.

## The Rule

**Business logic never lives in controllers, commands, or components.** It lives in an Action class under `app/Actions/{Domain}/`. The entry point's only job is to validate/parse input, build the Action, and call `execute()`.

## Architecture

```
HTTP Request ──► ActionableRequest ──► getAction() ──► BaseAction::execute()
Artisan CLI  ──► ActionableCommand ──► getAction() ──► BaseAction::execute()
Livewire/Job ──► direct construction ─────────────────► BaseAction::execute()
```

## Quick Reference

| Entry point | Bridge | How it calls the Action |
|---|---|---|
| Controller | `ActionableRequest` (FormRequest) | `$request->process()` — calls `getAction()->execute()` |
| Artisan CLI | `ActionableCommand` (Command) | `handle()` — calls `getAction()->execute()` with try/catch |
| Livewire | Direct | `(new MyAction($data, $user, $model))->execute()` |
| Queue Job | Direct | `(new MyAction($data, null, $model))->execute()` |

## Reference Files

Copyable base classes live in `references/`:

- `references/BaseAction.php` — abstract action base class
- `references/ActionableRequest.php` — HTTP bridge (FormRequest)
- `references/ActionableCommand.php` — CLI bridge (Artisan Command)

Copy these into a new project's `app/Actions/` and `app/Http/Requests/` and `app/Console/Commands/` respectively when bootstrapping the Action pattern.

---

## BaseAction

The root of all actions. See `references/BaseAction.php`.

### Constructor signature

```php
public function __construct(protected readonly array $data = [], ?User $user = null)
```

- `$data` — validated input as a key-value array. Comes from `FormRequest::validated()`, Artisan arguments/options, or Livewire component state.
- `$user` — the acting user. Falls back to `Auth::user()` when null (CLI and queue contexts pass null explicitly).

### Adding domain dependencies

When an Action needs a model (e.g., a `Site`, `Order`, `Post`), promote it as a **public readonly constructor param after `$data` and `$user`**:

```php
class UpdateOrderAction extends BaseAction
{
    public function __construct(array $data, ?User $user, public readonly Order $order)
    {
        parent::__construct($data, $user);
    }

    public function execute(): Order
    {
        $this->order->update($this->data);
        return $this->order->fresh();
    }
}
```

**Never bury models in `$data`.** The `$data` array is for scalar validated input. Models are explicit constructor params — this makes dependencies visible and type-safe.

### Return types

Actions return typed values. Declare the return type on `execute()`:

```php
public function execute(): Order    // single model
public function execute(): bool     // success/failure
public function execute(): int      // count
public function execute(): void     // side-effect only
```

The bridge decides how to present the return value (JSON, CLI output, Livewire state update).

---

## ActionableRequest (HTTP Bridge)

See `references/ActionableRequest.php`.

Every HTTP request that performs a write or triggers business logic extends `ActionableRequest`:

```php
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
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    public function getAction(): BaseAction
    {
        return new CreateOrderAction(
            $this->validated(),
            $this->user(),
        );
    }
}
```

### Controller usage

Controllers are one-liners:

```php
class OrderController extends Controller
{
    public function store(CreateOrderRequest $request): JsonResponse
    {
        return response()->json($request->process(), 201);
    }
}
```

**Rules:**
- `authorize()` uses Gate checks with route-model-bound entities.
- `getAction()` builds the Action from `$this->validated()`, `$this->user()`, and route-bound models — it runs no logic itself.
- `$request->process()` is the only call in the controller method.

---

## ActionableCommand (CLI Bridge)

See `references/ActionableCommand.php`.

Every Artisan command that performs business logic extends `ActionableCommand`:

```php
class CreateOrderCommand extends ActionableCommand
{
    protected $signature = 'orders:create {product : Product ID} {--quantity=1 : Quantity}';

    protected $description = 'Create a new order';

    protected function getAction(): BaseAction
    {
        $product = Product::findOrFail($this->argument('product'));

        return new CreateOrderAction(
            ['product_id' => $product->id, 'quantity' => (int) $this->option('quantity')],
            null,
            $product,
        );
    }

    protected function displayResult(mixed $result): void
    {
        $this->info("Order {$result->id} created successfully.");
    }
}
```

### How it works

`ActionableCommand` provides a `handle()` method that:

1. Calls `beforeAction()` — return `false` to abort (e.g., confirmation prompts)
2. Calls `getAction()` to build the Action from parsed arguments/options
3. Calls `execute()` on the Action inside a try/catch
4. Calls `afterAction($result)` — transform/format the result before display (returns it unchanged by default)
5. On success: calls `displayResult($result)` — **you implement this** to format CLI output
6. On failure: calls `displayError($exception)` — defaults to `$this->error($e->getMessage())`, override for custom error display

### Rules

- **`getAction()`** — resolve models from arguments, build the `$data` array from arguments/options, return the Action. No business logic here.
- **`displayResult()`** — format the Action's return value for the terminal. Use `$this->info()`, `$this->table()`, `$this->line()`, etc.
- **`afterAction($result)`** — transform the Action's return value before it reaches `displayResult()`. Useful for enriching, filtering, or reshaping the result for CLI presentation. Returns the result unchanged by default.
- **Don't override `handle()`** — the base class provides the before/execute/after/display flow. If you need pre-flight checks (e.g., confirming a destructive operation), override `beforeAction()`.

---

## Anti-Patterns

### Logic in the controller

```php
// BAD — logic in the controller
public function store(Request $request): JsonResponse
{
    $validated = $request->validate([...]);
    $order = Order::create($validated);
    Mail::to($order->user)->send(new OrderConfirmation($order));
    return response()->json($order, 201);
}

// GOOD — controller delegates to the Action
public function store(CreateOrderRequest $request): JsonResponse
{
    return response()->json($request->process(), 201);
}
```

### Logic in the command

```php
// BAD — business logic in handle()
public function handle(): int
{
    $product = Product::findOrFail($this->argument('product'));
    $order = Order::create(['product_id' => $product->id, ...]);
    Mail::to($order->user)->send(new OrderConfirmation($order));
    $this->info("Created order {$order->id}");
    return self::SUCCESS;
}

// GOOD — command delegates to the Action
protected function getAction(): BaseAction
{
    $product = Product::findOrFail($this->argument('product'));
    return new CreateOrderAction(['product_id' => $product->id, ...], null);
}

protected function displayResult(mixed $result): void
{
    $this->info("Created order {$result->id}");
}
```

### Models in `$data`

```php
// BAD — model buried in the data array
new UpdateOrderAction(['order' => $order, 'status' => 'shipped'], $user);

// GOOD — model is an explicit constructor param
new UpdateOrderAction(['status' => 'shipped'], $user, $order);
```

### Action aware of its entry point

```php
// BAD — Action knows it's being called from CLI
public function execute(): Order
{
    $order = Order::create($this->data);
    if (app()->runningInConsole()) {  // ← NEVER do this
        $this->logToConsole($order);
    }
    return $order;
}
```

---

## When to Use Each Bridge

| Scenario | Use |
|---|---|
| Form submission, API endpoint | `ActionableRequest` → controller calls `$request->process()` |
| Artisan command that does business work | `ActionableCommand` → implement `getAction()` + `displayResult()` |
| Livewire component method | Construct Action directly, call `->execute()` |
| Queue job wrapping an operation | Construct Action directly in `handle()`, call `->execute()` |
| Artisan command that only reads/reports | Regular `Command` — no Action needed |
| Artisan command that orchestrates (schedule, dispatch) | Regular `Command` — dispatching jobs is not business logic |

---

## Checklist for New Operations

1. Create the Action in `app/Actions/{Domain}/`
2. Extend `BaseAction`, promote domain models as constructor params
3. Implement `execute()` with a declared return type
4. Add the appropriate bridge:
   - **HTTP?** Create an `ActionableRequest` with `authorize()`, `rules()`, `getAction()`
   - **CLI?** Create an `ActionableCommand` with `$signature`, `getAction()`, `displayResult()`
   - **Both?** Create both — they share the same Action
5. Wire up the route/command registration
