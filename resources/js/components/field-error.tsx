/**
 * The validation message under a field. Renders nothing when there is no error,
 * so call sites don't repeat the `{form.errors.x && …}` guard around the markup.
 */
export function FieldError({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return <p className="text-xs text-red-400">{message}</p>;
}
