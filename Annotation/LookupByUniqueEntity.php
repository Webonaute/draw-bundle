<?php

namespace Draw\DrawBundle\Annotation;

/**
 * This annotation should be used in cases when you don't provide id in submitted payload (like Coupon code, not id)
 * and want to try perform deserialization into object based on UniqueEntity constraint
 *
 * @Annotation
 */
class LookupByUniqueEntity
{
}