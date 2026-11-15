<?php

namespace ErnestDefoe\Homecoming\Weather;

use RuntimeException;

/** The forum's one Nominatim request this second is taken: try again in a second. */
class LookupBusy extends RuntimeException
{
}
