<?php

namespace Spatie\Backup\Tasks\Backup;

class Encryption
{
    protected string $password;

    protected int $method;

    public function __construct(string $password, int $method)
    {
        $this->password = $password;

        $this->method = $method;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getMethod(): int
    {
        return $this->method;
    }
}
