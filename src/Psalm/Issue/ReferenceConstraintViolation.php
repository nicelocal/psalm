<?php

namespace Psalm\Issue;

class ReferenceConstraintViolation extends CodeIssue
{
    public static function getErrorLevel(): int { return 2; }
    public static function getShortCode(): int { return 86; }
}
