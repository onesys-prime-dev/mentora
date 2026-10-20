/*
|--------------------------------------------------------------------------
| How grades are written on screen, once (the twin of app/Helpers/grade_helper.php)
|--------------------------------------------------------------------------
|
| A percentage has up to two decimals and no trailing zeros: 85%, 85.5%,
| 85.25%. Anything that is not a number reads as the empty mark.
|
*/

/** A number with up to two decimals and no trailing zeros. */
export function gradeNumber(value, empty = "—")
{
    if (value === null || value === undefined || value === "" || Number.isNaN(Number(value))) {
        return empty;
    }

    return String(Math.round(Number(value) * 100) / 100);
}

/** A percentage, or the empty mark when there is no grade yet. */
export function gradePercent(value, empty = "—")
{
    const number = gradeNumber(value, null);

    return number === null ? empty : `${number}%`;
}
