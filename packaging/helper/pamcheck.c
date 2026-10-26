/*
 * pamcheck - tiny PAM authentication helper for ServerDeck.
 *
 * Usage: pamcheck <service> <username> authenticate
 * Password is read from stdin. Exit status is 0 only when PAM authentication
 * and account management both succeed.
 *
 * This deliberately prints nothing on success and never echoes the password.
 */

#define _GNU_SOURCE

#include <errno.h>
#include <security/pam_appl.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

#define MAX_PASSWORD 4096

struct credentials {
    const char *username;
    const char *password;
};

static int conversation(int count, const struct pam_message **messages,
                        struct pam_response **responses, void *data)
{
    const struct credentials *credentials = data;
    struct pam_response *reply;

    if (count <= 0 || messages == NULL || responses == NULL || credentials == NULL) {
        return PAM_CONV_ERR;
    }

    reply = calloc((size_t) count, sizeof(*reply));
    if (reply == NULL) {
        return PAM_BUF_ERR;
    }

    for (int i = 0; i < count; i++) {
        const int style = messages[i]->msg_style;
        const char *answer = NULL;

        if (style == PAM_PROMPT_ECHO_OFF) {
            answer = credentials->password;
        } else if (style == PAM_PROMPT_ECHO_ON) {
            answer = credentials->username;
        } else if (style == PAM_ERROR_MSG || style == PAM_TEXT_INFO) {
            continue;
        } else {
            free(reply);
            return PAM_CONV_ERR;
        }

        if (answer != NULL) {
            reply[i].resp = strdup(answer);
            if (reply[i].resp == NULL) {
                for (int j = 0; j < i; j++) {
                    free(reply[j].resp);
                }
                free(reply);
                return PAM_BUF_ERR;
            }
        }
    }

    *responses = reply;
    return PAM_SUCCESS;
}

static void wipe(char *value)
{
    if (value != NULL) {
        explicit_bzero(value, strlen(value));
    }
}

int main(int argc, char **argv)
{
    char *password = NULL;
    size_t capacity = 0;
    ssize_t length;
    struct pam_conv conv;
    struct credentials credentials;
    pam_handle_t *pamh = NULL;
    int status;

    if (argc < 4 || strcmp(argv[3], "authenticate") != 0) {
        fprintf(stderr, "usage: pamcheck <service> <username> authenticate\n");
        return 2;
    }
    if (strlen(argv[2]) == 0 || strlen(argv[2]) > 255 || strcmp(argv[1], "serverdeck") != 0) {
        return 1;
    }

    length = getline(&password, &capacity, stdin);
    if (length < 0) {
        free(password);
        return 1;
    }
    while (length > 0 && (password[length - 1] == '\n' || password[length - 1] == '\r')) {
        password[--length] = '\0';
    }
    if (length == 0 || (size_t) length > MAX_PASSWORD || strlen(password) != (size_t) length) {
        wipe(password);
        free(password);
        return 1;
    }

    credentials.username = argv[2];
    credentials.password = password;
    conv.conv = conversation;
    conv.appdata_ptr = &credentials;

    status = pam_start(argv[1], argv[2], &conv, &pamh);
    if (status == PAM_SUCCESS) {
        (void) pam_set_item(pamh, PAM_TTY, "serverdeck");
        status = pam_authenticate(pamh, PAM_SILENT);
    }
    if (status == PAM_SUCCESS) {
        status = pam_acct_mgmt(pamh, PAM_SILENT);
    }
    if (pamh != NULL) {
        (void) pam_end(pamh, status);
    }

    wipe(password);
    free(password);

    return status == PAM_SUCCESS ? 0 : 1;
}
