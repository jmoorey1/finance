<?php

declare(strict_types=1);

const REPORTING_PERIOD_BASIS_CALENDAR =
    'calendar';

const REPORTING_PERIOD_BASIS_HOUSEHOLD_FINANCIAL =
    'household_financial';

const REPORTING_PERIOD_BASIS_PAYE =
    'paye';

function reporting_period_basis_options(): array
{
    return [
        REPORTING_PERIOD_BASIS_CALENDAR =>
            'Calendar',

        REPORTING_PERIOD_BASIS_HOUSEHOLD_FINANCIAL =>
            'Household financial month',

        REPORTING_PERIOD_BASIS_PAYE =>
            'PAYE tax period',
    ];
}

function reporting_period_basis_label(
    string $basis
): string {
    $options =
        reporting_period_basis_options();

    if (
        !array_key_exists(
            $basis,
            $options
        )
    ) {
        throw new InvalidArgumentException(
            'Unknown reporting period basis: '
            . $basis
        );
    }

    return $options[
        $basis
    ];
}

function reporting_paye_tax_year_label(
    int $taxYearStart
): string {
    if (
        $taxYearStart < 1900
        || $taxYearStart > 2200
    ) {
        throw new InvalidArgumentException(
            'Invalid PAYE tax-year start.'
        );
    }

    return sprintf(
        '%d/%02d',
        $taxYearStart,
        ($taxYearStart + 1) % 100
    );
}

function reporting_paye_tax_month_label(
    int $taxMonth
): string {
    if (
        $taxMonth < 1
        || $taxMonth > 12
    ) {
        throw new InvalidArgumentException(
            'PAYE tax month must be between 1 and 12.'
        );
    }

    return 'Tax month '
        . $taxMonth;
}
