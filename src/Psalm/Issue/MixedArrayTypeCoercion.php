<?php

namespace Psalm\Issue;

class MixedArrayTypeCoercion extends CodeIssue implements MixedIssue
{
    public static $ERROR_LEVEL = 1;
    public static $SHORTCODE = 195;

    use MixedIssueTrait;
}
