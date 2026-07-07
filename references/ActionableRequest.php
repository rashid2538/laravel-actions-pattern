<?php

namespace App\Http\Requests;

use App\Actions\BaseAction;
use Illuminate\Foundation\Http\FormRequest;

abstract class ActionableRequest extends FormRequest
{
    abstract public function getAction(): BaseAction;

    public function process(): mixed
    {
        return $this->getAction()->execute();
    }
}
