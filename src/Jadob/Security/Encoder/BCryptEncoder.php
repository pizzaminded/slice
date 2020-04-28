<?php

namespace Jadob\Security\Encoder;

use RuntimeException;
use function password_hash;
use function password_verify;
use const PASSWORD_BCRYPT;

/**
 * Class BcryptEncoder
 *
 * @package Jadob\Security\Encoder
 * @author  pizzaminded <mikolajczajkowsky@gmail.com>
 * @license MIT
 */
class BCryptEncoder implements PasswordEncoderInterface
{
    /**
     * @var int
     */
    protected int $cost;

    /**
     * BcryptEncoder constructor.
     *
     * @param  int $cost
     * @throws RuntimeException
     */
    public function __construct(int $cost)
    {

        if ($cost < 4 || $cost > 31) {
            throw new RuntimeException('Invalid password cost passed');
        }

        $this->cost = $cost;
    }

    /**
     * @param string $raw
     * @param string $salt
     *
     * @return null|string
     */
    public function encode($raw, $salt = null): ?string
    {
        return password_hash(
            $raw, PASSWORD_BCRYPT, [
            'cost' => $this->cost
            ]
        );
    }

    /**
     * @param  string $raw
     * @param  string $hash
     * @return bool
     */
    public function compare($raw, $hash)
    {
        return password_verify($raw, $hash);
    }
}