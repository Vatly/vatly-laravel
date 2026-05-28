<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Vatly\Laravel\Billable;

class User extends Authenticatable
{
    use Billable;
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory()
    {
        return UserFactory::new();
    }
}

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => 'Test User',
            'email' => $this->faker->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'vatly_id' => null,
        ];
    }
}
