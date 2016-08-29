# benchmarking
Performance and Scalability Tests

## Setup

    sudo dnf install -y php-cli php-mysqlnd

## Usage

1. Google Cloud SQL certificate files should be in the project root directory.

2. Run the tests:

    export HOST=$DBIP
    export STORAGE=InsertDelete|InsertBatchDelete
    php DatabaseL2.php init $STORAGE $HOST
    seq 10 | xargs -n 1 -P 100 php DatabaseL2.php run $STORAGE $HOST

