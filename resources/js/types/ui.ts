export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type AppVariant = 'header' | 'sidebar';

/**
 * An action the toast offers to reverse what just happened.
 *
 * `data` is a snapshot the server took *before* it changed anything and
 * flashed back untouched — the client only has to post it where it is told.
 */
export type FlashToastUndo = {
    url: string;
    data: Record<string, string | null>;
};

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
    description?: string;
    /** How long the toast stays up, in milliseconds. */
    duration?: number;
    undo?: FlashToastUndo;
};
