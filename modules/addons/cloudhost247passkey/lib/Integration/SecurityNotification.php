<?php
/** Redacted notification envelope delivered only to an explicit host sink. */

namespace CloudHost247\Passkey\Integration;

use CloudHost247\Passkey\Model\SafeMetadata;
use CloudHost247\Passkey\Model\SecurityEventRecord;

class SecurityNotification
{
    const LOGIN = 'login';
    const SECURITY_EVENT = 'security_event';

    private $identity;
    private $notificationType;
    private $eventType;
    private $metadata;

    public function __construct(WhmcsIdentity $identity, $notificationType, $eventType, array $metadata = [])
    {
        if (!in_array($notificationType, [self::LOGIN, self::SECURITY_EVENT], true)) {
            throw new \InvalidArgumentException('Unsupported Passkey notification type.');
        }
        if (!in_array($eventType, SecurityEventRecord::TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported Passkey notification event type.');
        }
        $this->identity = $identity;
        $this->notificationType = $notificationType;
        $this->eventType = $eventType;
        // SafeMetadata rejects secret-shaped keys and drops arbitrary fields;
        // the callback never receives a raw event or profile payload.
        $this->metadata = SafeMetadata::eventArray(SafeMetadata::eventJson($metadata));
    }

    public function identity()
    {
        return $this->identity;
    }

    public function notificationType()
    {
        return $this->notificationType;
    }

    public function eventType()
    {
        return $this->eventType;
    }

    public function metadata()
    {
        return $this->metadata;
    }

    /** Public host-boundary view contains only an existing identity reference. */
    public function toArray()
    {
        return [
            'user_type' => $this->identity->userType(),
            'user_id' => $this->identity->userId(),
            'notification_type' => $this->notificationType,
            'event_type' => $this->eventType,
            'metadata' => $this->metadata,
        ];
    }
}
