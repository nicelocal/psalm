<?php

namespace Psalm\Issue;

class DuplicateEnumCaseValue extends ClassIssue
{
    public static function getErrorLevel(): int { return -1; }
    public static function getShortCode(): int { return 279; }
}
