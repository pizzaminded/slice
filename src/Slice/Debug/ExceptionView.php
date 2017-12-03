<?php

namespace Slice\Debug;


class ExceptionView
{
    public static function showErrorPage($template, $environment, array $params = [])
    {
        extract($params, true);

        require_once __DIR__ . '/Resources/templates/' . $environment . '/' . $template . '.php';

    }

    public static function getVariableType($variable): string
    {

        if(is_scalar($variable)) {
            return $variable;
        }

        if(is_object($variable)) {
            return get_class($variable);
        }

        if(is_array($variable)) {
            return 'array';
        }

        if(is_resource($variable)) {
            return 'resource';
        }

        return 'unknown';
    }

    public static function parseParams(array  $params = []): string
    {
        $output = [];
        foreach ($params as $param) {
            $output[] = self::getVariableType($param);
        }

        return implode(',', $output);
    }
}