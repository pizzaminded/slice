<?php
declare(strict_types=1);

namespace Jadob\Security\Auth\Exception;

/**
 * @author  pizzaminded <mikolajczajkowsky@gmail.com>
 * @license MIT
 */
class InvalidCredentialsException extends AuthenticationException
{

    public static function invalidCredentials(): self
    {
        return new self('security.invalid_credentials');
    }
}