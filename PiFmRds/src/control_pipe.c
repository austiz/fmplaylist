/*
    PiFmRds - FM/RDS transmitter for the Raspberry Pi
    Copyright (C) 2014 Christophe Jacquet, F8FTK

    See https://github.com/ChristopheJacquet/PiFmRds

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.

    control_pipe.c: handles command written to a non-blocking control pipe,
    in order to change RDS PS and RT at runtime.
*/


#include <string.h>
#include <stdlib.h>
#include <fcntl.h>
#include <stdio.h>
#include <unistd.h>
#include <errno.h>
#include <stdint.h>

#include "rds.h"
#include "control_pipe.h"

// Defined in pi_fm_rds.c. Live-tuning the carrier reuses the same
// runtime-mutable state the -hop scheduler writes to.
extern void set_carrier_freq_hz(uint32_t hz);
extern int set_hop_schedule(const char *spec);
extern void disable_hop_schedule(void);

#define CTL_BUFFER_SIZE 256

FILE *f_ctl;

/*
 * Opens a file (pipe) to be used to control the RDS coder, in non-blocking mode.
 */
int open_control_pipe(char *filename) {
	int fd = open(filename, O_RDONLY);
    if(fd < 0) return -1;

	int flags;
	flags = fcntl(fd, F_GETFL, 0);
	flags |= O_NONBLOCK;
	if( fcntl(fd, F_SETFL, flags) == -1 ) return -1;

	f_ctl = fdopen(fd, "r");
	if(f_ctl == NULL) return -1;

	return 0;
}


/*
 * Polls the control file (pipe), non-blockingly, and if a command is received,
 * processes it and updates the RDS data.
 */
int poll_control_pipe() {
	static char buf[CTL_BUFFER_SIZE];

    char *res = fgets(buf, CTL_BUFFER_SIZE, f_ctl);
    if(res == NULL) return -1;
    size_t len = strlen(res);
    if (len > 0 && res[len-1] == '\n') { res[len-1] = 0; len--; }

    // FREQ <MHz>  — retune the carrier without restarting.
    if (len > 5 && strncmp(res, "FREQ ", 5) == 0) {
        char *arg = res + 5;
        double mhz = atof(arg);
        uint32_t hz = (uint32_t)(mhz * 1e6);
        set_carrier_freq_hz(hz);
        printf("FREQ set to: %.3f MHz\n", mhz);
        return CONTROL_PIPE_FREQ_SET;
    }

    // HOP <f1,f2,...:dwell_ms>  — start/replace the hop schedule.
    // HOP OFF                   — stop hopping.
    if (len > 4 && strncmp(res, "HOP ", 4) == 0) {
        char *arg = res + 4;
        if (strcmp(arg, "OFF") == 0) {
            disable_hop_schedule();
            printf("HOP disabled.\n");
        } else if (set_hop_schedule(arg)) {
            printf("HOP schedule set: %s\n", arg);
        } else {
            printf("HOP: invalid spec \"%s\" (expected f1,f2,...:dwell_ms)\n", arg);
        }
        return CONTROL_PIPE_HOP_SET;
    }

    if(len > 3 && res[2] == ' ') {
        char *arg = res+3;
        if(res[0] == 'P' && res[1] == 'S') {
            arg[8] = 0;
            set_rds_ps(arg);
            printf("PS set to: \"%s\"\n", arg);
            return CONTROL_PIPE_PS_SET;
        }
        if(res[0] == 'R' && res[1] == 'T') {
            arg[64] = 0;
            set_rds_rt(arg);
            printf("RT set to: \"%s\"\n", arg);
            return CONTROL_PIPE_RT_SET;
        }
        if(res[0] == 'T' && res[1] == 'A') {
            int ta = ( strcmp(arg, "ON") == 0 );
            set_rds_ta(ta);
            printf("Set TA to ");
            if(ta) printf("ON\n"); else printf("OFF\n");
            return CONTROL_PIPE_TA_SET;
        }
    }

    return -1;
}

/*
 * Closes the control pipe.
 */
int close_control_pipe() {
    if(f_ctl) return fclose(f_ctl);
    else return 0;
}
