<?php

declare(strict_types=1);

namespace Psalm\Issue;

final class AssignmentToVoid extends CodeIssue
{
    public const ERROR_LEVEL = 7;
    public const SHORTCODE = 121;
}
