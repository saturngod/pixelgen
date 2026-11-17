import type { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}

/**
 * Show a balance in dollars, such as "$5.00" or "$0.2415".
 *
 * Keeps up to four decimals so it adds up with the image charges, and cuts off rather than rounds so it never looks higher than it is.
 */
export function formatBalance(balance: string): string {
    const [whole, fraction = ''] = balance.split('.');
    const decimals = fraction.slice(0, 4).replace(/0+$/, '').padEnd(2, '0');

    return `$${whole}.${decimals}`;
}

/**
 * Show a signed billing amount, such as "+$5.00" or "−$0.0707".
 *
 * Image charges are often a few cents or less, so up to four decimals are kept.
 */
export function formatAmount(amount: string): string {
    const isNegative = amount.startsWith('-');
    const [whole, fraction = ''] = amount.replace('-', '').split('.');
    const decimals = fraction.slice(0, 4).replace(/0+$/, '').padEnd(2, '0');

    return `${isNegative ? '−' : '+'}$${whole}.${decimals}`;
}
