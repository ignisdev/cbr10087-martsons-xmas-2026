/**
 * Augment Window typings and add in properties provided by Gravity Forms, WordPress, etc.
 */
interface Window {
    jQuery?: JQueryStatic
	GPAdvancedPhoneField: any
	GPAPF: {
		pluginUrl: string
		localizedCountries?: Record<string, string>
	}
	GPAPF_EDITOR: {
		strings: { [key: string]: string }
	}
	gform: {
		applyFilters: any
		doAction: any
	}
	fieldSettings: { [fieldType: string]: string }
	has_entry: (fieldID: string | number) => boolean
	SetFieldProperty: (setting: string, value: any) => void
	field: any
	SetFieldPhoneFormat: (format: string) => void
}
