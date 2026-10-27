/**
 * AgeValidator.js
 * Validates a date of birth against a minimum age requirement.
 */

export default class AgeValidator {
  /**
   * @param {number} minAge  Minimum required age in years (default 18)
   */
  constructor(minAge = 18) {
    this.minAge = minAge;
  }

  /**
   * Check whether a DOB string or Date represents someone who is at least minAge.
   *
   * @param {string|Date} dob  Date of birth — string formats: 'YYYY-MM-DD' | 'DD/MM/YYYY'
   * @returns {boolean}
   */
  isOldEnough(dob) {
    const birth = dob instanceof Date ? dob : this._parse(dob);
    if (!birth || isNaN(birth.getTime())) return false;

    const today = new Date();
    const threshold = new Date(
      birth.getFullYear() + this.minAge,
      birth.getMonth(),
      birth.getDate()
    );
    return today >= threshold;
  }

  /**
   * Parse common date string formats into a Date object.
   * @param {string} str
   * @returns {Date|null}
   */
  _parse(str) {
    if (!str) return null;

    // DD/MM/YYYY
    const dmy = str.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (dmy) {
      return new Date(Number(dmy[3]), Number(dmy[2]) - 1, Number(dmy[1]));
    }

    // YYYY-MM-DD (HTML date input)
    const ymd = str.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (ymd) {
      return new Date(Number(ymd[1]), Number(ymd[2]) - 1, Number(ymd[3]));
    }

    return null;
  }

  /**
   * Build a Date from separate day/month/year values (from split inputs).
   * @param {string|number} day
   * @param {string|number} month  1-based
   * @param {string|number} year
   * @returns {Date|null}
   */
  static fromParts(day, month, year) {
    const d = Number(day);
    const m = Number(month);
    const y = Number(year);
    if (!d || !m || !y || y < 1900) return null;
    return new Date(y, m - 1, d);
  }
}
