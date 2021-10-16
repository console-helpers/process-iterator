#!/usr/bin/env bash
MODE=$1
OUTPUT_TEXT=$2
SLEEP_INTERVAL=$3
EXIT_CODE=$4

case $1 in

  SLEEP)
    sleep $SLEEP_INTERVAL
    ;;

  EXIT)
    exit $EXIT_CODE
    ;;

  OUTPUT)
    echo $OUTPUT_TEXT
    ;;

  OUTPUT_AND_SLEEP)
    echo $OUTPUT_TEXT
    sleep $SLEEP_INTERVAL
    ;;

  SLEEP_AND_OUTPUT)
    sleep $SLEEP_INTERVAL
    echo $OUTPUT_TEXT
    ;;
esac
