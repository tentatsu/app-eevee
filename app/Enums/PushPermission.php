<?php

namespace App\Enums;

enum PushPermission: string
{
    case NotDetermined = 'not_determined';
    case Authorized = 'authorized';
    case Provisional = 'provisional';
    case Denied = 'denied';
}
