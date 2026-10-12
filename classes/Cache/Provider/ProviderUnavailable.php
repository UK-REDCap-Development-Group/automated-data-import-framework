<?php
namespace UKModules\ADIF\Cache\Provider;

/** Upstream service unreachable or returned an error. Triggers FailureMode handling. */
class ProviderUnavailable extends \RuntimeException {}
