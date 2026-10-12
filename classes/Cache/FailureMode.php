<?php
namespace UKModules\ADIF\Cache;

/** What to do when the service is down or errors. */
enum FailureMode: string
{
    /** Fall back to a cached value even if stale, and flag it as stale. */
    case UseStale = 'use_stale';

    /** Leave the value empty and report the error. */
    case Fail = 'fail';
}
