/**
 * The shape both environment files satisfy.
 *
 * Declared rather than inferred so `apiUrl` is a `string` in both. With
 * `as const` the dev file's type would be the literal `'http://localhost:8000'`,
 * and any code comparing it against the production empty string would be a
 * compile error instead of a runtime branch.
 */
export interface Environment {
  production: boolean;
  /** Empty in production, where the API is same-origin and reached by path. */
  apiUrl: string;
}
