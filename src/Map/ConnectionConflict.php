<?php

namespace Goldnead\BrandContext\Map;

use DomainException;

/**
 * The field a connection would write is no longer what the user confirmed:
 * somebody else changed it in the meantime. Nothing was written.
 */
class ConnectionConflict extends DomainException {}
