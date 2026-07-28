#include "internal.h"
#include <unistd.h>

/* Headless backend: never creates a native window. Used for CI / smoke tests
 * and as the platform default when no GUI is available. */

static void null_realize(ui3_app *app) { (void)app; }

static void null_update_text(ui3_widget *w, const char *t) {
    (void)w;
    (void)t;
}

static void null_update_int(ui3_widget *w, int v) {
    (void)w;
    (void)v;
}

static int null_step(ui3_app *app) {
    if (app->quit) return 1;
    usleep(2000); /* don't busy-spin a blocking run() */
    return 0;
}

static void null_run(ui3_app *app) {
    while (!null_step(app)) { /* loop until quit */ }
}

static void null_quit(ui3_app *app) { (void)app; }

static void null_destroy_app(ui3_app *app) { (void)app; }

void ui3_null_init(ui3_backend_ops *ops) {
    ops->realize = null_realize;
    ops->update_text = null_update_text;
    ops->update_int = null_update_int;
    ops->step = null_step;
    ops->run = null_run;
    ops->quit = null_quit;
    ops->destroy_app = null_destroy_app;
}
