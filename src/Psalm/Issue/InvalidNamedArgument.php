<?php

namespace Psalm\Issue;

class InvalidNamedArgument extends ArgumentIssue
{
    public static function getErrorLevel(): int { return 6; }
    public static function getShortCode(): int { return 238; }
}
