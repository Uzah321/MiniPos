<?php

namespace App\Kitchen;

enum KitchenOrderStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Acknowledged = 'acknowledged';
    case Accepted = 'accepted';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case ServedOrCollected = 'served_or_collected';
    case Completed = 'completed';

    case Held = 'held';
    case Unavailable = 'unavailable';
    case ChangePending = 'change_pending';
    case Cancelled = 'cancelled';
    case Voided = 'voided';
}
