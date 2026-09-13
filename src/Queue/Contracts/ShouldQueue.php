<?php

namespace Nitro\Queue\Contracts;

/**
 * Marker: this should be handled on a queue rather than in the request.
 *
 * Implemented by an event listener or a notification to move its work out of
 * the response the user is waiting on. The thing that raises the event does not
 * know or care — that is the point of the seam. A certificate is issued because
 * an attempt passed; whether the PDF is rendered now or in a worker a second
 * later is a delivery decision, not a domain one.
 *
 * Nothing is called on this interface. It exists to be asked about.
 */
interface ShouldQueue
{
}
