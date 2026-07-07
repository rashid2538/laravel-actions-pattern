<?php

namespace App\Console\Commands;

use App\Actions\BaseAction;
use Illuminate\Console\Command;

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

    protected function beforeAction(): bool
    {
        return true;
    }

    protected function afterAction(mixed $result): mixed
    {
        return $result;
    }

    protected function displayError(\Throwable $e): void
    {
        $this->error($e->getMessage());
    }
}
