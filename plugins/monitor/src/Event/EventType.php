<?php
/**
 * Shared monitoring event types.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Event;

final class EventType {
	public const SITE_DOWN             = 'SITE_DOWN';
	public const RECOVERED             = 'RECOVERED';
	public const AGENT                 = 'AGENT';
	public const UPDATES               = 'UPDATES';
	public const SITE_HEALTH_CRITICAL  = 'SITE_HEALTH_CRITICAL';
	public const SITE_HEALTH_RECOVERED = 'SITE_HEALTH_RECOVERED';
	public const SSL                   = 'SSL';
}
