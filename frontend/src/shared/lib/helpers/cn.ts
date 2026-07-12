import { clsx, type ClassValue } from 'clsx'
import { extendTailwindMerge } from 'tailwind-merge'

const themeColors = [
  'accent',
  'accent-dark',
  'accent-text',
  'accent-light',
  'bg-deep',
  'bg-surface',
  'bg-primary',
  'bg-offset',
  'bg-card',
  'bg-elevated',
  'text-primary',
  'text-muted',
  'text-disabled',
  'border',
  'border-subtle',
  'success',
  'warning',
  'danger',
  'info',
  'focus',
]

const themeFontSizes = [
  'display-2xl',
  'display-xl',
  'display-lg',
  'display-md',
  'display-sm',
  'body-lg',
  'body-md',
  'body-sm',
  'body-xs',
  'label',
]

const twMerge = extendTailwindMerge({
  extend: {
    classGroups: {
      'text-color': themeColors.map((color) => `text-${color}`),
      'bg-color': themeColors.map((color) => `bg-${color}`),
      'border-color': themeColors.map((color) => `border-${color}`),
      'ring-color': themeColors.map((color) => `ring-${color}`),
      'font-size': themeFontSizes.map((size) => `text-${size}`),
    },
  },
})

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}
