<?php

namespace App\Enums;

enum DocumentReviewStatus: string
{
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';
}
