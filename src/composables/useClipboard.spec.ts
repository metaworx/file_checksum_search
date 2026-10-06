import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useClipboard } from './useClipboard'

describe('useClipboard', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	it('copies text and auto-resets the copied flag', async () => {
		const writeText = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		})

		const { copied, copyToClipboard } = useClipboard()

		await copyToClipboard('abc123')
		expect(writeText).toHaveBeenCalledWith('abc123')
		expect(copied.value).toBe(true)

		vi.advanceTimersByTime(2000)
		expect(copied.value).toBe(false)
	})

	it('keeps the flag false when the clipboard API fails', async () => {
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText: vi.fn().mockRejectedValue(new Error('denied')) },
			configurable: true,
		})

		const { copied, copyToClipboard } = useClipboard()
		await copyToClipboard('abc123')
		expect(copied.value).toBe(false)
	})
})
