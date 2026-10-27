/// <reference types="vite/client" />

// Vite raw imports — ?raw suffix returns the file contents as a string
declare module '*.svg?raw' {
	const content: string;
	export default content;
}

// Vite asset imports — returns the resolved public URL string
declare module '*.glb' {
	const url: string;
	export default url;
}
