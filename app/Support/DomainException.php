<?php

namespace App\Support;

/**
 * A business-rule violation whose message is safe to show to the user
 * (Filament actions catch it and display it as a danger notification).
 */
class DomainException extends \DomainException {}
