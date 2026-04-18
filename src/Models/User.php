<?php

namespace Nudelsalat\Models;

use Nudelsalat\ORM\Model;
use Nudelsalat\Migrations\Fields\IntField;
use Nudelsalat\Migrations\Fields\StringField;

class User extends Model
{
    public static function fields(): array
    {
        return [
            'id' => new IntField(primaryKey: true, autoIncrement: true),
            'username' => new StringField(length: 50),
            'email' => new StringField(length: 255, nullable: true),
            'password' => new StringField(length: 128),
        ];
    }
}
