<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

abstract class BaseAction
{
    protected readonly ?User $user;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(protected readonly array $data = [], ?User $user = null)
    {
        $this->user = $user ?? Auth::user();
    }

    abstract public function execute(): mixed;
}
