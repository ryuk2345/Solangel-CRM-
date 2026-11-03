<?php

namespace App\Policies;

class WholesalePriceRequestPolicy extends ShieldPolicy
{
    protected string $key = 'wholesale::price::request';
}
