<?php
namespace Database\Factories;use App\Models\User;use Illuminate\Database\Eloquent\Factories\Factory;use Illuminate\Support\Facades\Hash;use Illuminate\Support\Str;
final class UserFactory extends Factory{protected $model=User::class;public function definition():array{return['full_name'=>fake()->name(),'email'=>fake()->unique()->safeEmail(),'phone_e164'=>'+2376'.fake()->unique()->numerify('########'),'email_verified_at'=>now(),'phone_verified_at'=>now(),'password'=>Hash::make('ChangeMe!2026'),'locale'=>'en','status'=>'ACTIVE','remember_token'=>Str::random(10)];}}
