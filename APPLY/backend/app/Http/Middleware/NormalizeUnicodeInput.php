<?php

namespace App\Http\Middleware;

use App\Support\TextNormalizer;
use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * Folds styled-font Unicode in every incoming request field down to plain characters
 * before anything reads it.
 *
 * This sits in the global middleware stack rather than in a controller so that the
 * normalization cannot be skipped by changing the frontend, calling the API directly,
 * or adding a new endpoint later: by the time any controller, validator or model sees
 * the input, it is already normalized. Because it runs ahead of validation, rules like
 * `max:255` and the mobile-number regex are checked against the value that will
 * actually be stored.
 *
 * @see \App\Support\TextNormalizer
 */
class NormalizeUnicodeInput extends TransformsRequest
{
    /**
     * Fields that must reach the application byte-for-byte as they were typed.
     *
     * Passwords are compared against a stored hash, so folding a fullwidth or
     * non-breaking character inside one would lock the account out.
     *
     * @var array<int, string>
     */
    protected $except = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function transform($key, $value)
    {
        if (in_array($key, $this->except, true)) {
            return $value;
        }

        return TextNormalizer::normalize($value);
    }
}
