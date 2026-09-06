<?php

namespace Goldnead\BrandContext\Http\Requests;

use Goldnead\BrandContext\Settings\SettingsRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the settings form, generated from the registry.
 *
 * The rules are derived rather than written out, so a field cannot be added to
 * an addon's `settingsGroups()` and forgotten here — which is the failure that
 * lets an unvalidated value reach `config()` and, from there, every reader in
 * the addon.
 *
 * One namespace per request. The screen posts the section it is saving, not
 * the whole page, so a validation failure in one addon cannot block saving
 * another, and the permission check below is about the namespace actually
 * being written rather than about the page as a whole.
 */
class UpdateBrandSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! method_exists($user, 'can')) {
            return false;
        }

        $namespace = $this->namespace();

        // An unregistered namespace is refused here rather than in the rules.
        // Falling through to validation would produce "the settings field is
        // required" for a namespace that does not exist, which reads as a form
        // bug rather than as the refusal it is.
        if ($namespace === null) {
            return false;
        }

        $permission = $this->registry()->permission($namespace);

        // A registered namespace always has one; null here would mean the
        // registry and the contract disagree, and the safe reading of that is
        // "no", not "everybody".
        return $permission !== null && $user->can($permission);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'namespace' => ['required', 'string'],
            'settings' => ['required', 'array'],
        ];

        $namespace = $this->namespace();

        if ($namespace === null) {
            return $rules;
        }

        foreach ($this->registry()->fields($namespace) as $key => $field) {
            // Dots are path separators to the validator, and these keys carry
            // real dots (`runs.prune_after_days`). Escaped, the rule addresses
            // the one key rather than a nested structure that does not exist.
            $at = 'settings.'.str_replace('.', '\\.', $key);

            $rules[$at] = match ($field['type'] ?? 'string') {
                'boolean' => ['present', 'boolean'],
                'integer' => array_values(array_filter([
                    'present',
                    ($field['nullable'] ?? false) ? 'nullable' : 'required',
                    'integer',
                    isset($field['min']) ? 'min:'.$field['min'] : null,
                    isset($field['max']) ? 'max:'.$field['max'] : null,
                ])),
                'list' => ['present', 'array'],
                // The whole point of a select is that the set is closed. A
                // rule of `string` here would accept anything the browser was
                // told to send, which is everything.
                'select' => array_values(array_filter([
                    'present',
                    ($field['nullable'] ?? false) ? 'nullable' : 'required',
                    Rule::in(array_column(
                        SettingsRegistry::normaliseOptions($field['options'] ?? []),
                        'value'
                    )),
                ])),
                default => [
                    'present',
                    ($field['nullable'] ?? false) ? 'nullable' : 'required',
                    'string',
                    'max:255',
                ],
            };

            if (($field['type'] ?? null) === 'list') {
                // `nullable`, because Laravel's ConvertEmptyStringsToNull has
                // already turned a blank line from the textarea into null by
                // the time the rules run. Rejecting it would mean the form
                // refuses to save over a trailing newline — and the blanks are
                // dropped on the way in anyway, so nothing empty is stored.
                //
                // The entry rule follows the declared item type: a list of
                // status codes that accepts "nope" would store it and then
                // coerce it to 0, which matches nothing and says nothing.
                $rules[$at.'.*'] = ($field['items'] ?? 'string') === 'integer'
                    ? ['nullable', 'integer']
                    : ['nullable', 'string', 'max:255'];
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $attributes = [];
        $namespace = $this->namespace();

        if ($namespace === null) {
            return $attributes;
        }

        foreach ($this->registry()->fields($namespace) as $key => $field) {
            $attributes['settings.'.$key] = $field['label'] ?? $key;
        }

        return $attributes;
    }

    /** The registered namespace this request writes, or null if it names none. */
    public function namespace(): ?string
    {
        $namespace = $this->input('namespace');

        if (! is_string($namespace) || ! $this->registry()->has($namespace)) {
            return null;
        }

        return $namespace;
    }

    protected function registry(): SettingsRegistry
    {
        return app(SettingsRegistry::class);
    }
}
