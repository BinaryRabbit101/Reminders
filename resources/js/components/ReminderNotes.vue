<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * A reminder's notes, as a full-width row across the bottom of the card —
 * prose reads badly in the narrow column left over beside the date and the
 * action buttons, so it gets the whole width and a hairline rule to sit
 * under.
 *
 * The text clamps to three lines so a long description can't swallow the
 * card. Nothing is cut: when the clamp actually hides text, a Show more /
 * Show less toggle reveals the full notes in place. Overflow is measured
 * live (mount + element resize) so the toggle only appears when there is
 * genuinely more to show, and stays honest through viewport changes like
 * phone rotation.
 */
const { notes, overdue = false } = defineProps<{
    notes: string;
    /** Matches the card's destructive tint so the rule doesn't read as grey-on-red. */
    overdue?: boolean;
}>();

const body = ref<HTMLElement | null>(null);
const expanded = ref(false);
const overflows = ref(false);

// Only measure while clamped — expanded text never overflows, and measuring
// it would clear the flag and drop the "Show less" control mid-read.
function measure() {
    if (body.value && !expanded.value) {
        overflows.value = body.value.scrollHeight > body.value.clientHeight + 1;
    }
}

let observer: ResizeObserver | undefined;
onMounted(() => {
    measure();
    observer = new ResizeObserver(measure);

    if (body.value) {
        observer.observe(body.value);
    }
});
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div
        class="mt-1.5 w-full min-w-0 border-t px-1 pt-1.5 text-sm text-muted-foreground"
        :class="overdue ? 'border-destructive/25' : 'border-border/60'"
        data-test="reminder-notes-row"
    >
        <p
            ref="body"
            class="break-words"
            :class="{ 'line-clamp-3': !expanded }"
            data-test="reminder-notes"
        >
            {{ notes }}
        </p>
        <!--
            .stop keeps the click from reaching a parent card whose own
            click opens the edit sheet — expanding notes is not editing.
        -->
        <button
            v-if="overflows || expanded"
            type="button"
            class="mt-0.5 text-xs font-medium underline underline-offset-4 transition-colors hover:text-foreground"
            :aria-expanded="expanded"
            data-test="reminder-notes-toggle"
            @click.stop="expanded = !expanded"
        >
            {{ expanded ? 'Show less' : 'Show more' }}
        </button>
    </div>
</template>
