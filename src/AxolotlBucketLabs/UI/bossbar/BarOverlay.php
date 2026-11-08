<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\bossbar;

/** Boss bar segment styles. Values are the Bedrock protocol ids. */
enum BarOverlay: int
{
    case PROGRESS = 0;
    case NOTCHED_6 = 1;
    case NOTCHED_10 = 2;
    case NOTCHED_12 = 3;
    case NOTCHED_20 = 4;
}
