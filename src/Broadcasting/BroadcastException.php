<?php

namespace Nitro\Broadcasting;

use RuntimeException;

/**
 * A broadcast could not be delivered.
 *
 * Its own type because a broadcast failing is rarely the application failing:
 * the row was saved, the mail went, and only the live update was lost. A
 * caller that wants to carry on can catch this without swallowing everything.
 */
class BroadcastException extends RuntimeException
{
}
