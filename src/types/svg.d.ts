declare module '*.svg' {
	const content: string
	export default content
}

// Vite's `?raw` suffix: the file's text, here an SVG to inline.
declare module '*.svg?raw' {
	const content: string
	export default content
}
