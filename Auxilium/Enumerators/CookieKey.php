<?php

namespace Auxilium\Enumerators;

/**
 * String backed enumerator for cookie keys.
 */
enum CookieKey: string
{
    case LANGUAGE = "language";
    case STYLE = "style";
}
