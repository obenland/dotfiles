/**
 * Launcher for the nightly claude-retro job on macOS.
 *
 * launchd starts this binary instead of /bin/bash, so Full Disk Access can be
 * granted to this one program rather than to every bash script. macOS gives
 * the job's privacy permissions to the process launchd starts and to its
 * children, so the launcher spawns run.sh as a child and waits; exec'ing bash
 * would hand the permission check to /bin/bash instead.
 *
 * It ignores the inherited environment and argv: the home directory comes from
 * the password database, and the only thing it ever runs is
 * ~/claude-retro/run.sh with a fixed PATH.
 *
 * Built and ad-hoc signed per machine by install.sh. Rebuilding changes the
 * signature and drops the Full Disk Access grant, so install.sh only rebuilds
 * when this source changes.
 */

#include <limits.h>
#include <pwd.h>
#include <spawn.h>
#include <stdio.h>
#include <stdlib.h>
#include <sys/wait.h>
#include <unistd.h>

/**
 * Spawns run.sh with a clean environment and returns its exit status.
 *
 * @return int Exit status of run.sh, or 1 on launcher errors.
 */
int main( void ) {
	struct passwd *pw = getpwuid( getuid() );
	if ( pw == NULL || pw->pw_dir == NULL || pw->pw_name == NULL ) {
		fprintf( stderr, "claude-retro-launcher: can't look up the current user\n" );
		return 1;
	}

	char script[ PATH_MAX ];
	char home[ PATH_MAX + 8 ];
	char user[ 256 ];
	char path[ PATH_MAX + 128 ];
	snprintf( script, sizeof( script ), "%s/claude-retro/run.sh", pw->pw_dir );
	snprintf( home, sizeof( home ), "HOME=%s", pw->pw_dir );
	snprintf( user, sizeof( user ), "USER=%s", pw->pw_name );
	snprintf( path, sizeof( path ), "PATH=%s/.local/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin", pw->pw_dir );

	char *child_argv[] = { "/bin/bash", script, NULL };
	char *child_env[ 8 ] = { home, user, path, "LANG=en_US.UTF-8", NULL };
	int n = 4;

	/* Pass through only what launchd provides for git over SSH and temp files. */
	char ssh[ PATH_MAX + 16 ];
	char tmp[ PATH_MAX + 16 ];
	const char *value = getenv( "SSH_AUTH_SOCK" );
	if ( value != NULL ) {
		snprintf( ssh, sizeof( ssh ), "SSH_AUTH_SOCK=%s", value );
		child_env[ n++ ] = ssh;
	}
	value = getenv( "TMPDIR" );
	if ( value != NULL ) {
		snprintf( tmp, sizeof( tmp ), "TMPDIR=%s", value );
		child_env[ n++ ] = tmp;
	}
	child_env[ n ] = NULL;

	pid_t pid;
	int err = posix_spawn( &pid, "/bin/bash", NULL, NULL, child_argv, child_env );
	if ( err != 0 ) {
		fprintf( stderr, "claude-retro-launcher: can't start %s (error %d)\n", script, err );
		return 1;
	}

	int status;
	if ( waitpid( pid, &status, 0 ) < 0 ) {
		perror( "claude-retro-launcher: waitpid" );
		return 1;
	}
	return WIFEXITED( status ) ? WEXITSTATUS( status ) : 1;
}
